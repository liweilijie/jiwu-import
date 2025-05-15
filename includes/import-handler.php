<?php

// 定时任务执行函数：查询未导入的房源记录并导入为 Houzez 主题的 Property 帖子
function jiwu_import_cron_task()
{
    // 检查是否已有任务在运行
    if ( get_transient( 'jiwu_import_cron_lock' ) ) {
        return; // 有任务在运行，退出
    }

    // 设置锁，持续时间
    set_transient( 'jiwu_import_cron_lock', true, 3600*10);

    // 注册关闭函数，在脚本结束时释放锁
    register_shutdown_function( function() {
        error_log("delete_transient jiwu_import_cron_lock");
        delete_transient( 'jiwu_import_cron_lock' );
    } );

    global $wpdb;
    // 构造自定义表名（考虑表前缀）
    $table_name = $wpdb->prefix . 'listings';

    // 从自定义表中获取所有 status=0 的房源记录
    $listings = $wpdb->get_results("SELECT * FROM {$table_name} WHERE status = 0");
    if (!$listings) {
        return; // 如果没有待处理记录，则无需继续
    }

    foreach ($listings as $listing) {
        // 校验记录必要字段是否合法
        if (!jiwu_import_validate_listing($listing)) {
            // 若缺少必要字段，标记为无效(status=2)并跳过
            $wpdb->update(
                $table_name,
                array('status' => 2),
                array('id' => $listing->id)
            );
            continue;
        }

        // 借鉴feed里面的import流程进行导入开发
        $unique_id = $listing->unique_id;
        $imported_ref_key = '_imported_ref_jiwu';

        $args = [
            'post_type' => 'property',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => $imported_ref_key,
                    'value' => $unique_id
                ]
            ]
        ];
        $property_query = new WP_Query($args);

        $inserted_updated = false;

        $parts = [];
        if (!empty($listing->street)) $parts[] = $listing->street;
        if (!empty($listing->suburb)) $parts[] = $listing->suburb;
        if (!empty($listing->state)) $parts[] = strtoupper($listing->state);
        if (!empty($listing->postcode)) $parts[] = $listing->postcode;

        $display_address = implode(', ', $parts);
        $post_id = 0;

        if ($property_query->have_posts()) {
            $property_query->the_post();
            $post_id = get_the_ID();

            $update_data = [
                'ID' => $post_id,
                'post_title' => wp_strip_all_tags($display_address),
                'post_excerpt' => $listing->title ?? '',
                'post_content' => $listing->description ?? '',
                'post_status' => 'publish'
            ];

            $post_id = wp_update_post($update_data, true);

            // debug
            error_log(print_r($update_data, true));
            error_log(print_r($post_id, true));

            if (!is_wp_error($post_id)) {
                $wpdb->update($table_name, ['status' => 1, 'post_id' => $post_id], ['id' => $listing->id]);
            } else {
                $inserted_updated = 'updated';
            }
        } else {
            $post_data = [
                'post_type' => 'property',
                'post_status' => 'publish',
                'post_title' => wp_strip_all_tags($display_address),
                'post_excerpt' => $listing->title ?? '',
                'post_content' => $listing->description ?? '',
                'comment_status' => 'closed',
            ];

            $post_id = wp_insert_post($post_data, true);
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, $imported_ref_key, $unique_id);
                $wpdb->update($table_name, ['status' => 1, 'post_id' => $post_id], ['id' => $listing->id]);
                $inserted_updated = 'inserted';
            } else {
                $wpdb->update($table_name, ['status' => 2], ['id' => $listing->id]);
            }
        }
        $property_query->reset_postdata();

        if ($inserted_updated !== false) {
            // 更新房源唯一标识
            update_post_meta($post_id, '_imported_ref_jiwu', $listing->unique_id);

            if ($listing->price_text) {
                update_post_meta($post_id, 'fave_property_price', $listing->price_text); // 面议
                update_post_meta($post_id, 'fave_property_price_postfix', '');
            } else {
                // 更新价格信息
                if ($listing->lower_price) {
                    update_post_meta($post_id, 'fave_property_price', $listing->lower_price);
                    update_post_meta($post_id, 'fave_property_price_postfix', '');
                } else {
                    update_post_meta($post_id, 'fave_property_price', 'Contact Agent'); // 面议
                    update_post_meta($post_id, 'fave_property_price_postfix', '');
                }
            }


            // TODO: 出租的信息处理待定
            // 检查房源类型是否为“出租”
            if (isset($listing->listing_type) && strtolower($listing->listing_type) === 'rental') {
                // 默认租金周期为每周
                $rentFreq = 'pw';

                // 尝试从 features 中获取租金周期信息
                $features = json_decode($listing->features, true);
                if (isset($features['rent_period'])) {
                    $period = strtolower(trim($features['rent_period']));
                    if (in_array($period, ['month', 'monthly'], true)) {
                        $rentFreq = 'pcm';
                    }
                }

                // 更新房源的价格后缀元字段
                update_post_meta($post_id, 'fave_property_price_postfix', $rentFreq);
            }


            // 更新卧室、浴室、车位数量
            update_post_meta($post_id, 'fave_property_bedrooms', $listing->bedrooms);
            update_post_meta($post_id, 'fave_property_bathrooms', $listing->bathrooms);
            update_post_meta($post_id, 'fave_property_garage', $listing->car_spaces);
            update_post_meta( $post_id, 'fave_property_id', $unique_id);     // 房源唯一外部 ID

            // 更新面积信息
            if ($listing->land_size) {
                update_post_meta($post_id, 'fave_property_size', $listing->land_size);
                update_post_meta($post_id, 'fave_property_size_prefix', 'm²');
            } else {
                update_post_meta($post_id, 'fave_property_size', '');
                update_post_meta($post_id, 'fave_property_size_prefix', '');
            }

            // 根据房源的状态（如“current”、“sold”、“withdrawn”、“offmarket”）和类型（如“rental”）设置相应的分类，以便在前端进行筛选和展示。
            $status = strtolower($listing->listing_type); // 例如：'sale' 或 'rental'
            $term_name = '';

            switch ($status) {
                case 'sale':
                    $term_name = 'For Sale';
                    break;
                case 'rental':
                    $term_name = 'For Rent';
                    break;
                case 'sold':
                    $term_name = 'Sold';
                    break;
                case 'withdrawn':
                    $term_name = 'Withdrawn';
                    break;
                case 'offmarket':
                    $term_name = 'Off Market';
                    break;
                default:
                    // 未知状态，记录日志或采取其他处理方式
                    error_log("未知的房源状态：{$status}");
                    return;
            }

            // 检查 term 是否存在
            $term = get_term_by('name', $term_name, 'property_status');

            if (!$term) {
                // 如果 term 不存在，则创建
                $result = wp_insert_term($term_name, 'property_status');
                if (is_wp_error($result)) {
                    // 创建失败，记录错误
                    error_log("无法创建术语 '{$term_name}'：{$result->get_error_message()}");
                    return;
                }
                $term_id = $result['term_id'];
            } else {
                $term_id = $term->term_id;
            }

            // 将术语与文章关联
            wp_set_object_terms($post_id, (int)$term_id, 'property_status');

            // features
            $features = json_decode($listing->features, true);
            $additional = [];

            if (!empty($features)) {
                $taxonomy = 'property_feature';
                $term_ids = [];

                // 遍历每个 feature，只有当 value 不是空、不是 false 的时候才加入
                foreach ($features as $feature_key => $feature_value) {
                    if (empty($feature_value)) {
                        continue; // 跳过空值或 false
                    }

                    // 格式化显示标题：驼峰转空格并首字母大写
                    // 例如 airConditioning -> Air Conditioning
                    $title = ucwords(
                        preg_replace(
                            '/([a-z])([A-Z])/',
                            '$1 $2',
                            $feature_key
                        )
                    );

                    // 数值或布尔也都可以直接转成字符串
                    $value = (string)$feature_value;

                    // 将这一项加入到 additional_features 数组中
                    $additional[] = [
                        'fave_additional_feature_title' => $title,
                        'fave_additional_feature_value' => $value,
                    ];


                    // 将特征键转换为术语名称（例如：'dishwasher' => 'Dishwasher'）
                    $term_name = ucwords(str_replace('_', ' ', $feature_key));

                    // 检查术语是否已存在
                    $term = get_term_by('name', $term_name, $taxonomy);

                    if (!$term) {
                        // 如果术语不存在，则创建
                        $result = wp_insert_term($term_name, $taxonomy);
                        if (is_wp_error($result)) {
                            // 如果创建失败，记录错误并跳过
                            error_log("无法创建术语 '{$term_name}': " . $result->get_error_message());
                            continue;
                        }
                        $term_id = $result['term_id'];
                    } else {
                        $term_id = $term->term_id;
                    }

                    $term_ids[] = (int)$term_id;
                }

                if (!empty($term_ids)) {
                    // 将所有术语关联到文章，追加方式，保留已有的术语
                    wp_set_object_terms($post_id, $term_ids, $taxonomy, true);
                }

            }

            //  写入 post meta：
            //    Houzez 的前端示例里，additional_features 存的是一个序列化的多维数组
            //    WordPress 底层会自动帮你 serialize() / unserialize()
            // 假设 $post_id 是当前物业的文章 ID
            // 假设你已经将解析好的附加特征数组存到 $additional_features 中
            if ( ! empty( $additional) ) {
                // 将附加特征写入 meta
                update_post_meta($post_id, 'additional_features', $additional);
                // 打开显示开关
                update_post_meta( $post_id, 'fave_additional_features_enable', 'enable' );
            } else {
                // 没有附加特征时，关闭显示
                update_post_meta( $post_id, 'fave_additional_features_enable', 'disable' );
            }


            // address
            // 提取地址信息
            $state   = isset($listing->state) ? strtoupper(trim($listing->state)) : '';
            $country = isset($listing->country) ? strtoupper(trim($listing->country)) : 'Australia'; // 默认澳大利亚
            $suburb  = isset($listing->suburb) ? trim($listing->suburb) : '';
            $street  = isset($listing->street) ? trim($listing->street) : '';
            $postcode = isset($listing->postcode) ? trim($listing->postcode) : '';
            $area    = isset($listing->area) ? trim($listing->area) : $suburb;

            // 更新分类法（taxonomy）
            // 关联 state 到 property_state 分类法
            if (!empty($state)) {
                $term = term_exists($state, 'property_state');
                if (!$term) {
                    $term = wp_insert_term($state, 'property_state');
                }
                if (!is_wp_error($term)) {
                    wp_set_object_terms($post_id, (int)$term['term_id'], 'property_state');
                }
            }

            // 关联 country 到 property_country 分类法
            if (!empty($country)) {
                $term = term_exists($country, 'property_country');
                if (!$term) {
                    $term = wp_insert_term($country, 'property_country');
                }
                if (!is_wp_error($term)) {
                    wp_set_object_terms($post_id, (int)$term['term_id'], 'property_country');
                }
            }

            // 更新元字段（meta fields）
            update_post_meta($post_id, 'fave_property_city', $suburb);
            update_post_meta($post_id, 'fave_property_state', $state);
            update_post_meta($post_id, 'fave_property_zip', $postcode);
            update_post_meta($post_id, 'fave_property_area', $area);
            update_post_meta($post_id, 'fave_property_country', $country);

            // 构建用于地图显示的完整地址
            $address_parts = array_filter([$street, $suburb, $state, $postcode]);
            $display_address = implode(', ', $address_parts);
            update_post_meta($post_id, 'fave_property_map', 1);
            update_post_meta($post_id, 'fave_property_map_address', $display_address);

            $address_parts = array();
            if ( isset($listing->address->street) && (string)$listing->address->street != '' )
            {
                $address_parts[] = (string)$listing->address->street;
            }
            update_post_meta( $post_id, 'fave_property_address', implode(", ", $address_parts) );
            update_post_meta( $post_id, 'fave_featured', '' );

            // 获取经纬度信息
            $latitude  = isset($listing->latitude) ? $listing->latitude : '';
            $longitude = isset($listing->longitude) ? $listing->longitude : '';

            if (!empty($latitude) && !empty($longitude)) {
                // 如果经纬度信息存在，直接更新
                update_post_meta($post_id, 'fave_property_location', "{$latitude},{$longitude},14");
            } else {
                // 如果经纬度信息不存在，可以在此处调用地理编码服务获取
                // 例如使用 Google Maps Geocoding API 或其他服务
                // 这里省略具体实现 TODO:
            }

            // TODO: agent

            // property_type
            // 获取 property_type 字段
            $property_type = $listing->property_type ?? '';

            if (!empty($property_type)) {
                $taxonomy = 'property_type';

                // 检查术语是否已存在
                $term = get_term_by('name', $property_type, $taxonomy);

                if (!$term) {
                    // 如果术语不存在，则创建
                    $result = wp_insert_term($property_type, $taxonomy);
                    if (is_wp_error($result)) {
                        // 记录错误日志
                        error_log("无法创建物业类型 '{$property_type}': " . $result->get_error_message());
                        return;
                    }
                    $term_id = $result['term_id'];
                } else {
                    $term_id = $term->term_id;
                }

                // 将术语与当前文章关联
                $set_result = wp_set_object_terms($post_id, (int)$term_id, $taxonomy, false);
                if (is_wp_error($set_result)) {
                    // 记录错误日志
                    error_log("无法将物业类型 '{$property_type}' 分配给文章 ID {$post_id}: " . $set_result->get_error_message());
                }
            }

            // 解析图片 URL 数组
            $images = json_decode( $listing->images, true );
            $media_ids = [];

            if ( ! empty( $images ) && is_array( $images ) ) {
                foreach ( $images as $img_url ) {
                    error_log(print_r($img_url, true));
                    // 验证 URL 是否有效
                    if ( empty( $img_url ) || ! filter_var( $img_url, FILTER_VALIDATE_URL ) ) {
                        continue;
                    }

                    $image_id = 0;
                    $result = EXMAGE_WP_IMAGE_LINKS::add_image( $img_url, $image_id, $post_id );

                    error_log(print_r($result, true));

                    if (
                        (isset($result['status']) && $result['status'] === 'success' && isset($result['id'])) ||
                        (isset($result['status']) && $result['status'] === 'error' && isset($result['message']) && $result['message'] === 'Image exists' && isset($result['id']))
                    ) {
                        $media_ids[] = $result['id'];
                    } else {
                        // 记录错误信息
                        error_log('EXMAGE 添加图片失败: ' . ($result['message'] ?? '未知错误'));
                    }

//                    if ( isset( $result['status'] ) && $result['status'] === 'success' && isset( $result['id'] ) ) {
//                        $media_ids[] = $result['id'];
//                    } else {
//                        // 记录错误信息
//                        error_log( 'EXMAGE 添加图片失败: ' . ( $result['message'] ?? '未知错误' ) );
//                    }
                }

                error_log(print_r($media_ids, true));

                // 设置特色图像为第一张图片
                if ( ! empty( $media_ids ) ) {
                    set_post_thumbnail( $post_id, $media_ids[0] );
                }

                // 将所有图片附件 ID 添加到 'fave_property_images' 元数据中
                delete_post_meta( $post_id, 'fave_property_images' );
                foreach ( $media_ids as $mid ) {
                    add_post_meta( $post_id, 'fave_property_images', $mid );
                }
            }

            // 解析户型图 URL 数组
            $floorplan_urls = json_decode($listing->floor_plan, true);

            if (!empty($floorplan_urls) && is_array($floorplan_urls)) {
                $floorplans = [];

                foreach ($floorplan_urls as $plan_url) {
                    // 验证 URL 是否有效
                    if (filter_var($plan_url, FILTER_VALIDATE_URL)) {
                        $floorplans[] = [
                            'fave_plan_title' => __('Floorplan', 'jiwu-import'),
                            'fave_plan_image' => $plan_url,
                        ];
                    }
                }

                if (!empty($floorplans)) {
                    // 将户型图信息写入文章元数据
                    update_post_meta($post_id, 'floor_plans', $floorplans);
                    update_post_meta($post_id, 'fave_floor_plans_enable', 'enable');
                } else {
                    update_post_meta($post_id, 'fave_floor_plans_enable', 'disable');
                }
            }

            // agency agent
            $agency = json_decode($listing->agency, true);
            $agency_id = 0;
            $agency_name = '';

            if (!empty($agency) && is_array($agency)) {
                $agency_name = sanitize_text_field($agency['name']);
                $agency_address = sanitize_text_field($agency['address']);
                // 检查机构是否已存在
                $existing_agency = get_page_by_title($agency_name, OBJECT, 'houzez_agency');

                if ($existing_agency) {
                    $agency_id = $existing_agency->ID;
                } else {
                    // 创建新的机构帖子
                    $agency_post = [
                        'post_title'  => $agency_name,
                        'post_type'   => 'houzez_agency',
                        'post_status' => 'publish',
                    ];
                    $agency_id = wp_insert_post($agency_post);

                    if (is_wp_error($agency_id)) {
                        $agency_id = 0;
                    } else {
                        // 添加机构元数据
                        update_post_meta($agency_id, 'fave_agency_address', $agency_address);
                    }
                }
                if ($agency_id) {
                    // 将房源关联到经纪公司（若需要在房源页显示公司信息)
                    update_post_meta($post_id, 'fave_property_agency', $agency_id );
                    // 此处可根据需要添加其它 Agency 元信息，例如:
                    // update_post_meta($agency_id, 'fave_agency_email', $agency_email);
                    // update_post_meta($agency_id, 'fave_agency_phone', $agency_phone);
                }
            }

            $agents = json_decode($listing->agents, true);

            if (!empty($agents) && is_array($agents)) {
                foreach ($agents as $agent_data) {
                    $agent_name = sanitize_text_field($agent_data['name']);
                    $agent_phone = sanitize_text_field($agent_data['phone']);
                    $agent_photo_url = esc_url_raw($agent_data['photo_url']);

                    $agent_id = 0;

                    // 检查代理是否已存在
                    // 先尝试通过邮箱查找已存在的 Agent
                    if ( !$agent_id && !empty($agent_phone) ) {
                        $existing_agents = get_posts( array(
                            'post_type'  => 'houzez_agent',
                            'meta_query' => array(
                                array(
                                    'key'   => 'fave_agent_mobile',
                                    'value' => $agent_phone
                                )
                            ),
                            'numberposts' => 1
                        ) );
                        if ( !empty($existing_agents) ) {
                            $agent_id = $existing_agents[0]->ID;
                        }
                    }

                    // 如仍未找到则根据姓名查找（可选，根据需要启用）
                    if ( !$agent_id && !empty($agent_name) ) {
                        $existing_agent = get_page_by_title($agent_name, OBJECT, 'houzez_agent');
                        if ($existing_agent) {
                            $agent_id = $existing_agent->ID;
                        }
                    }


                    // 如果还不存在，则创建新的 Agent
                    if ( !$agent_id ) {
                        // 创建新的代理帖子
                        $agent_post = [
                            'post_title'  => $agent_name,
                            'post_type'   => 'houzez_agent',
                            'post_status' => 'publish',
                            // 可以根据需要添加'post_author' => 某用户ID，如果想关联到WP用户
                        ];
                        $agent_id = wp_insert_post($agent_post);

                        if (is_wp_error($agent_id)) {
                            continue; // 如果创建失败，跳过
                        }
//                        // 设置 Agent 元字段（邮箱、电话等）:contentReference[oaicite:25]{index=25}
//                        if ( !empty($agent_email) ) {
//                            update_post_meta( $agent_id, 'fave_agent_email', $agent_email );
//                        }
//                        if ( !empty($agent_mobile) ) {
//                            update_post_meta( $agent_id, 'fave_agent_mobile', $agent_mobile );
//                        }
//                        if ( !empty($agent_office_phone) ) {
//                            update_post_meta( $agent_id, 'fave_agent_office_num', $agent_office_phone );
//                        }

                        // 添加代理元数据
                        if ( !empty($agent_phone) ) {
                            update_post_meta($agent_id, 'fave_agent_mobile', $agent_phone);
                            update_post_meta($agent_id, 'fave_agent_office_num', $agent_phone);
                        }

                        // （可选）设置Agent其他信息字段，如公司名等:
                        // update_post_meta($agent_id, 'fave_agent_company', $agency_name );
                        // 若有照片URL，可调用媒体函数添加头像，略。
                        // （可选）设置Agent默认模板，避免主题警告:
                        update_post_meta( $agent_id, 'slide_template', 'default' );

                        // 处理代理照片
                        if (!empty($agent_photo_url)) {
                            // 验证 URL 是否有效
                            if ( empty( $agent_photo_url) || ! filter_var( $agent_photo_url, FILTER_VALIDATE_URL ) ) {
                                error_log(print_r($agent_photo_url, true));
                            } else {
                                $image_id = 0;
                                $result = EXMAGE_WP_IMAGE_LINKS::add_image( $agent_photo_url, $image_id, $agent_id);

                                error_log(print_r($result, true));

                                if (
                                    (isset($result['status']) && $result['status'] === 'success' && isset($result['id'])) ||
                                    (isset($result['status']) && $result['status'] === 'error' && isset($result['message']) && $result['message'] === 'Image exists' && isset($result['id']))
                                ) {
                                    $media_ids[] = $result['id'];
                                } else {
                                    // 记录错误信息
                                    error_log('EXMAGE 添加图片失败: ' . ($result['message'] ?? '未知错误'));
                                }
                            }
                        }
                    }

                    // -------- 3. 关联 Agent 与 Agency，并绑定到 Property --------
                    if ( $agent_id ) {
                        if ($agency_id) {
                            // 将经纪人关联到经纪公司
                            update_post_meta($agent_id, 'fave_agent_agencies', $agency_id);
                            update_post_meta($agent_id, 'fave_agent_position', 'fave_agent_position');
                        }
                        if (!empty($agent_name)) {
                            // 将经纪人关联到经纪公司
                            update_post_meta($agent_id, 'fave_agent_company', $agent_name);
                        }
                        // 将房源关联到经纪人:contentReference[oaicite:29]{index=29}
                        update_post_meta( $post_id, 'fave_agents', $agent_id );
                        // 设置房源显示选项为显示经纪人信息:contentReference[oaicite:30]{index=30}
                        update_post_meta( $post_id, 'fave_agent_display_option', 'agent_info' );
                    }
                }
            }
        }

    }
}